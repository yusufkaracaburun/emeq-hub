<?php

use App\Http\Controllers\Webhooks\MollieWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes — /webhooks/{provider}/{...}
|--------------------------------------------------------------------------
| Publiek; signature is de auth. NIET geprefixed met /v1/. Geregistreerd
| in bootstrap/app.php's withRouting()->then()-callback.
*/

Route::post('/webhooks/mollie/{connection_id}', MollieWebhookController::class)
    ->where('connection_id', '[0-9]+')
    ->name('webhooks.mollie');
