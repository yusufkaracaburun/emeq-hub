<?php

use App\Http\Controllers\Api\V1\PingController;
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
});
