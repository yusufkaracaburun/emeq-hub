<?php

use App\Http\Controllers\Webhooks\ExactWebhookController;
use App\Http\Controllers\Webhooks\MollieWebhookController;
use App\Http\Controllers\Webhooks\SnelstartWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\AftercareWebhookController;
use Laravel\Cashier\Http\Controllers\FirstPaymentWebhookController;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

Route::post('/webhooks/mollie/{connection_id}', MollieWebhookController::class)
    ->where('connection_id', '[0-9]+')
    ->name('webhooks.mollie');

Route::post('/webhooks/snelstart', SnelstartWebhookController::class)
    ->middleware(['verify.snelstart.signature'])
    ->withoutMiddleware(['throttle:api'])
    ->name('webhooks.snelstart');

Route::post('/webhooks/exact', ExactWebhookController::class)
    ->middleware(['verify.exact.signature'])
    ->withoutMiddleware(['throttle:api'])
    ->name('webhooks.exact');

Route::middleware('cashier.webhook.secret')->group(function (): void {
    Route::post('/cashier/webhook', [CashierWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.default');

    Route::post('/cashier/webhook/first-payment', [FirstPaymentWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.first_payment');

    Route::post('/cashier/webhook/aftercare', [AftercareWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.aftercare');
});
