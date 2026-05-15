<?php

use App\Http\Controllers\Webhooks\MollieWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\AftercareWebhookController;
use Laravel\Cashier\Http\Controllers\FirstPaymentWebhookController;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

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

/*
 * Cashier-Mollie webhook-ingress (D-10/D-11). Separaat van Phase 5a's
 * /webhooks/mollie/{connection_id} Connect-route. Hard-fail guard via
 * cashier.webhook.secret-middleware. Geen fan-out — Cashier handle't
 * subscription-state-machine intern. Cashier's eigen default-routes zijn
 * uitgezet via Cashier::ignoreRoutes() in AppServiceProvider::register().
 */
Route::middleware('cashier.webhook.secret')->group(function (): void {
    Route::post('/cashier/webhook', [CashierWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.default');

    Route::post('/cashier/webhook/first-payment', [FirstPaymentWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.first_payment');

    Route::post('/cashier/webhook/aftercare', [AftercareWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.aftercare');
});
